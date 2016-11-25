(function($) {
    var app = angular.module('app', ['infinite-scroll']); 
    //directive that cheks if an image is loaded
    /*app.directive('imageonload', function() {
        return {
            restrict: 'A',
            link: function(scope, element, attrs) {
                element.bind('load', function() {
                    return true;
                });
                element.bind('error', function(){
                    return false;
                });
            }
        };
    });*/

    app.controller('pictureController', ['$scope', function($scope) {
        $scope.data =[];
        $scope.masterArray =[];
        $scope.orginalArray =[]; // The purpose of this array is to store the original ayout ofthe masterArray
        //temporary function that creates fake data in the same format as I expect to get the data though an API
        var getFakeData = function(){
            var data = {};           
            for (var i = 0; i < 1000; i++){
                data[i] = { url: "/img/200.jpeg" }; //"http://loremflickr.com/200/200?random="+i
                if ((i%2)==0){
                    data[i]["socialPlatform"] = "FB"
                    data[i]["likes"] = 10000 + (i)*2
                    data[i]["party"] = "Pirate party of Slovenia"
                }
                else {
                    data[i]["socialPlatform"] = "TW"
                    data[i]["likes"] = 10000 + (-i)*2
                    data[i]["party"] = "Pirate party of Austria"
                };
            };
            return data;
    	};


        //this functions loads more data for the infinite scroll
        // it constantily updates the array from which ng-rpeat gets its data
        $scope.loadMore = function() {
            var last = [];
            var x
            if ( $scope.data.length == 0){
                x = 0
            }
            else {
                x = $scope.data.length - 1;
            }
            for(var i = 1; i <= 20; i++) {
                var currentValue = $scope.masterArray[x+i];        
                $scope.data.push(currentValue);
            }
        };

        $.when(getFakeData()).done(function(data){ 
            for (var x in data){
                $scope.masterArray.push(data[x]); 
            };
            $scope.originalArray = $scope.masterArray.slice();
            $scope.loadMore(); 

        });

        //Function that runs after data is acquired
        //it transforms the object into an array and runs 


        
        //function that sorts entries by reach in ascending order
        $scope.sortAscViews = function(){ 
            $scope.masterArray.sort(function(a, b){
            return a.likes > b.likes;
        });
        $scope.data = [];
        $scope.loadMore();
        };
        //function that sorts entries by reach in descending order
        $scope.sortDescViews = function(){
            $scope.masterArray.sort(function(a, b){
            return a.likes < b.likes;
        });
        $scope.data = [];
        $scope.loadMore();
        };

        $scope.defaultSort = function(){
        $scope.masterArray = $scope.originalArray.slice(); 
        $scope.data = [];
        $scope.loadMore(); 
        $(".up").removeClass("arrow-color")
        $(".down").removeClass("arrow-color")
        }

        //function that toggles between ascending and descending amount of reach
        $scope.sortByViews = function(){
            if ( $("#asc-desc-views").hasClass("toggled") ) {
                $("#asc-desc-views").removeClass("toggled")
                $(".up").removeClass("arrow-color")
                $(".down").addClass("arrow-color")
                $scope.sortAscViews()
            }
            else {
                $("#asc-desc-views").addClass("toggled")
                $(".up").addClass("arrow-color")
                $(".down").removeClass("arrow-color")
                $scope.sortDescViews();
            };

        } 

        $('#platform-selection').bind('click', function (e) { e.stopPropagation() })

    }]);
})(jQuery); // end of jQuery name space